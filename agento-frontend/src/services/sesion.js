/**
 * Sesión del panel interno: guarda el token de acceso y lo RENUEVA antes de que venza.
 *
 * El access token dura poco (JWT_TTL, 60 min por defecto). El backend solo acepta renovar un token
 * vigente (POST /refresh va detrás de `jwt`), así que la renovación es preventiva: cuando queda poco
 * para vencer, antes de la siguiente petición, se pide uno nuevo. Un usuario activo no ve nunca un
 * cierre de sesión; con más de un TTL de inactividad la sesión sí vence (comportamiento deseado).
 *
 * Este módulo no depende del navegador ni de axios: recibe el almacenamiento y la función que
 * llama a /refresh, para poder verificarlo de forma aislada.
 */
export const CLAVE_TOKEN = 'access_token';
const CLAVE_EXPIRA = 'access_token_expira';
const CLAVE_TTL = 'access_token_ttl';

/** Margen para renovar: el menor entre 10 min y un cuarto de la vida del token. */
const MARGEN_MAXIMO_MS = 10 * 60 * 1000;

const leer = (almacen, clave) => {
  try {
    return almacen.getItem(clave);
  } catch {
    return null;
  }
};

const escribir = (almacen, clave, valor) => {
  try {
    almacen.setItem(clave, String(valor));
  } catch {
    // Sin almacenamiento: la sesión no podrá renovarse, pero tampoco se rompe.
  }
};

export function guardarSesion(almacen, { access_token: token, expires_in: segundos }, ahora = Date.now()) {
  escribir(almacen, CLAVE_TOKEN, token);
  if (Number.isFinite(Number(segundos)) && Number(segundos) > 0) {
    const ttlMs = Number(segundos) * 1000;
    escribir(almacen, CLAVE_EXPIRA, ahora + ttlMs);
    escribir(almacen, CLAVE_TTL, ttlMs);
  }
}

export function limpiarSesion(almacen) {
  [CLAVE_TOKEN, CLAVE_EXPIRA, CLAVE_TTL].forEach((clave) => {
    try {
      almacen.removeItem(clave);
    } catch {
      // Nada que limpiar.
    }
  });
}

/** ¿Hay que renovar ya? Solo si se conoce cuándo vence el token y falta menos del margen. */
export function debeRenovar(almacen, ahora = Date.now()) {
  const expira = Number(leer(almacen, CLAVE_EXPIRA));
  const ttl = Number(leer(almacen, CLAVE_TTL));
  if (!leer(almacen, CLAVE_TOKEN) || !Number.isFinite(expira) || expira <= 0) return false;

  const margen = Math.min(MARGEN_MAXIMO_MS, Number.isFinite(ttl) && ttl > 0 ? ttl / 4 : MARGEN_MAXIMO_MS);

  return expira - ahora <= margen;
}

/**
 * @param {object} opciones
 * @param {Storage} opciones.almacen
 * @param {(token: string) => Promise<{access_token: string, expires_in: number}>} opciones.llamarRefresh
 * @param {() => number} [opciones.ahora]
 * @returns {() => Promise<void>} garantiza un token vigente; nunca lanza (si no puede renovar, la
 * petición sigue con el token actual y el 401 del servidor cierra la sesión si corresponde).
 */
export function crearRenovador({ almacen, llamarRefresh, ahora = Date.now }) {
  let enCurso = null;

  const renovar = async () => {
    const tokenUsado = leer(almacen, CLAVE_TOKEN);
    try {
      guardarSesion(almacen, await llamarRefresh(tokenUsado), ahora());
    } catch (error) {
      // Otra pestaña pudo renovar a la vez: el servidor invalida el token anterior en cuanto se
      // renueva, así que nuestro intento da 401 aunque la sesión esté bien. Si el token guardado
      // ya cambió, esa pestaña ganó y todo está en orden.
      if (leer(almacen, CLAVE_TOKEN) !== tokenUsado) return;
      // Cualquier otro fallo (red, 5xx, 401 real): seguimos con el token actual.
      if (error?.response?.status === 401) throw error;
    }
  };

  return async function asegurarVigente() {
    if (!debeRenovar(almacen, ahora())) return;

    // Una sola renovación a la vez aunque haya muchas peticiones en paralelo.
    enCurso ??= renovar().finally(() => {
      enCurso = null;
    });
    try {
      await enCurso;
    } catch {
      // El 401 ya lo gestiona el interceptor de respuesta de la petición original.
    }
  };
}
