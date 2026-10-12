# ADR-001: Monolito modular

Nuvora Cloud se implementa como un único despliegue Laravel con módulos en `app/Modules`. Cada módulo separa presentación, aplicación, dominio e infraestructura.

Se descarta separar servicios en esta etapa porque el producto todavía no tiene una carga ni un equipo que justifiquen la operación distribuida. Los límites de módulo permiten extraer un servicio más adelante si una medición lo exige.
