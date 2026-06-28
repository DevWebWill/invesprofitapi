# Calculos y reglas de negocio

Este documento centraliza como se calculan las metricas principales del proyecto.

## Objetivo

- Evitar ambiguedades en formulas y criterios de inclusion.
- Explicar por que una metrica puede quedar en 0 o null.
- Mantener trazabilidad entre formula y codigo.

## Donde vive cada calculo

- Proceso de propiedades raw: app/Console/Commands/ProcessRawProperties.php
- Agregaciones de mercados por zona: app/Http/Controllers/Api/MarketController.php

## Proceso raw de propiedades

### 1) Estimacion de monthly_rent para inmuebles de venta

Metodo: estimateMonthlyRent

Regla:
1. Tomar comparables de alquiler validos cercanos.
2. Calcular rent_per_m2 por comparable: price_value / area_m2.
3. Calcular mediana local de rent_per_m2.
4. monthly_rent_estimado = mediana(rent_per_m2) * area_m2 del inmueble en venta.
5. Aplicar cap por ciudad para limitar outliers.

Casos donde devuelve null:
- area_m2 <= 0
- lat/lng ausentes o en 0
- comparables insuficientes

### 2) Calculo de yield_gross y yield_net

Metodo: computeMetrics

Precondiciones:
- price > 0
- monthly_rent != null
- monthly_rent > 0

Formula:
- yield_gross = round(((monthly_rent * 12) / price) * 100, 1)
- yield_gross se limita a maximo 99.9
- yield_net = round(yield_gross * 0.75, 1)

Si no se cumplen precondiciones:
- yield_gross = 0
- yield_net = 0
- investment_score = 0

### 3) Calculo de investment_score

Metodo: computeScore

Composicion:
- 70% componente de yield
- 30% componente de precio por m2 frente a mediana local

Interpretacion:
- Precio por m2 por debajo de mediana local mejora score.

## Mercados: agregacion por zona

Metodo: aggregateZones

### Clasificacion de operacion

Metodo: resolveOperation

- sale: precio de venta util y sin renta util
- rent: listing_mode rent o solo renta util
- both: listing_mode sale con precio y monthly_rent validos

Nota: both cuenta para inventario de venta y de renta.

### KPIs por zona

- avgSalePriceSqm = promedio(price / area_m2) con registros de venta validos
- avgRentPriceSqm = promedio(monthly_rent / area_m2) con monthly_rent > 0 y area_m2 > 0
- avgMonthlyRent = promedio(monthly_rent) con monthly_rent > 0
- estimatedYield = promedio(yield_gross) con yield_gross > 0
- investmentScore = promedio(investment_score)

## Estandar para cualquier calculo nuevo

Para cada nuevo calculo en el proyecto:
1. Documentar formula exacta y unidades.
2. Documentar precondiciones y casos de salida 0/null.
3. Documentar fuente de datos de entrada.
4. Dejar docblock en el metodo que implementa la formula.
5. Añadir o actualizar este documento en el mismo PR.

Con esto, la documentacion queda en ambos niveles: codigo y proyecto.
