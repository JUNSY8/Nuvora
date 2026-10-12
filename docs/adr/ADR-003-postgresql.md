# ADR-003: PostgreSQL

PostgreSQL es el sistema de registro. Las invariantes que la base pueda garantizar (unicidad, llaves foráneas, checks) no quedan solo en la aplicación.

Las pruebas automatizadas usan SQLite en memoria para mantenerse deterministas sin un servicio externo. Ese atajo no cambia el motor de desarrollo ni de producción.
