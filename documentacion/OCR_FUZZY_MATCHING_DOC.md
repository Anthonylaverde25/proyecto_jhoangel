# Resolución Inteligente de Razas (OCR Fuzzy Matching)

Este documento explica el mecanismo implementado para manejar la asociación de razas de ganado provenientes de lecturas de OCR, resolviendo los problemas de errores tipográficos y falsos positivos.

## 1. El Problema Original
Anteriormente, el sistema utilizaba reglas "harcodeadas" (rígidas) basadas en la función `str_contains` para intentar adivinar qué raza había leído el OCR.
Por ejemplo: si la lectura contenía las letras `"bra"`, el sistema asumía automáticamente que era `"Brangus"`. 

**Consecuencias de este enfoque:**
- **Falsos Positivos:** Una raza válida como `"Braford"` (que contiene "bra") era forzada erróneamente a ser guardada como `"Brangus"`.
- **Suciedad en Base de Datos:** Si el OCR leía algo que no encajaba en ninguna regla (ej. `"brangu"`), el sistema creaba un nuevo registro en la tabla `breeds` en lugar de asociarlo a `"Brangus"`.

## 2. La Solución Implementada (Distancia de Levenshtein)
Para resolver esto, implementamos un algoritmo matemático conocido como la **Distancia de Levenshtein**. Este algoritmo calcula cuántos "pasos" (inserciones, eliminaciones o sustituciones de letras) se requieren para transformar una palabra en otra.

Gracias a esto, el sistema ahora se comporta como un humano al leer la planilla:
- Si lee `"brangu"`, sabe que a un solo paso de distancia está `"Brangus"`, por lo que lo asocia inteligentemente.
- Si lee `"Braford"`, encuentra una coincidencia exacta y no interfiere.

## 3. Arquitectura (Clean Architecture)

El proceso se divide en la Capa de Aplicación y la Capa de Dominio (Core).

### A. `app/Core/Services/CaravanValueParser.php` (El Limpiador)
Se le quitó toda la lógica de "adivinación". Ahora su única responsabilidad es recibir el texto del OCR, aplicarle un `trim` (quitar espacios sobrantes) y pasarlo a formato limpio (Primera letra mayúscula, resto minúscula).

### B. `app/Core/Services/BreedMatcherService.php` (El Motor de Inteligencia)
Es un Servicio de Dominio puro. Su responsabilidad es buscar coincidencias lógicas.
Tiene dos fases de evaluación:
1. **Pass 1 (Exact Match):** Compara la lectura exactamente contra la base de datos (ignorando mayúsculas/minúsculas).
2. **Pass 2 (Fuzzy Match):** Si no hay coincidencia exacta, calcula la distancia de Levenshtein contra todas las razas de la base de datos. Si encuentra una raza cuya distancia sea **menor o igual a 2 cambios** (`MAX_DISTANCE = 2`), asume que fue un error del OCR y retorna esa raza.

### C. `app/Application/UseCases/Caravans/ImportCaravansUseCase.php` (El Orquestador)
El caso de uso coordina el flujo:
1. Al iniciar la importación, extrae todas las razas existentes en la base de datos (para no hacer miles de queries).
2. Envía la lectura del OCR y la lista de razas al `BreedMatcherService`.
3. **Decisión:**
   - Si el servicio devuelve una coincidencia (ej. vinculó "brangu" con "Brangus"), usa ese ID.
   - Si el servicio devuelve `null` (la lectura es totalmente nueva y no se parece a nada conocido, ej. "Wagyu"), recién entonces llama al repositorio para crear una nueva raza en la base de datos.

## 4. ¿Cómo ajustar la tolerancia?
Si en el futuro notas que el sistema está asociando razas erróneamente porque son muy parecidas (ej. "RazaA" y "RazaB" solo tienen 1 letra de diferencia), puedes volver el sistema más estricto.

1. Abre el archivo `app/Core/Services/BreedMatcherService.php`.
2. Modifica la constante:
   ```php
   private const MAX_DISTANCE = 2; // Cámbialo a 1 para ser más estricto.
   ```
   - `2`: Permite hasta 2 letras de error (Ideal para OCR ruidoso).
   - `1`: Permite solo 1 letra de error.
   - `0`: Fuerza a que el match sea exacto (anula la inteligencia artificial de similitud).

---
*Documento generado siguiendo el estándar arquitectónico del proyecto (VIERNES).*
