# ADR-008: API versionada

La API pública vive en `/api/v1`. Un cambio incompatible exige una versión nueva. Los errores usan el contrato `success`, `error.code`, `error.message`, `error.details` y `request_id`.

La interfaz Vue es una aplicación aparte y consume esa API con cookies de sesión de Sanctum. El proxy de Vite en desarrollo evita tratar el navegador como otro origen.
