# Comandos Artisan — InvesProfitAPI

Referencia de todos los comandos disponibles y su orden de ejecución recomendado.

## Documentacion de calculos

- Guia central de formulas y reglas: docs/calculos-y-reglas.md
- Esta guia se debe actualizar cada vez que cambie cualquier calculo de negocio.

---

## Flujo completo desde cero

```bash
# 1. Migraciones y base de datos
php artisan migrate

# 2. (Opcional) Usuarios de prueba
php artisan db:seed

# 3. Procesar datos raw → tabla properties
php artisan properties:process-raw --fresh

# 4. Capturar snapshot de mercado
php artisan markets:snapshot --overwrite
```

---

## Comandos de datos

### `properties:process-raw`

Lee las tablas `raw_sale_properties` y `raw_rent_properties` (datos brutos de Fotocasa u otros portales) y puebla la tabla `properties` con cálculos reales: renta estimada por comparables cercanos, yield bruto/neto e investment score.

```bash
# Reprocesar todo desde cero
php artisan properties:process-raw --fresh

# Solo una ciudad (sin borrar el resto)
php artisan properties:process-raw --city=Madrid

# Actualizar sin borrar (añade/actualiza)
php artisan properties:process-raw
```

**Qué calcula:**
- `monthly_rent` (ventas): mediana de €/m² de alquileres reales en radio 1.5 km × area_m2 del inmueble
- `yield_gross`: `(monthly_rent × 12 / price) × 100`
- `yield_net`: `yield_gross × 0.75` (descuento típico de gastos: IBI, comunidad, vacíos, reparaciones)
- `investment_score` (0–100): 70% rentabilidad + 30% precio vs mercado

---

### `markets:snapshot`

Genera un snapshot mensual de métricas de mercado por zona (ciudad/distrito/barrio) a partir de los datos que hay en `properties`. Se guarda en `market_zone_snapshots` y lo consume la API de mercados.

```bash
# Capturar el mes actual (sobreescribe si ya existe)
php artisan markets:snapshot --overwrite

# Capturar un mes concreto
php artisan markets:snapshot --month=2026-06 --overwrite

# Capturar sin sobreescribir (falla si ya existe ese mes)
php artisan markets:snapshot
```

**Orden importante:** ejecutar siempre **después** de `properties:process-raw`, ya que el snapshot lee la tabla `properties`.

---

## Cómo leer Mercados

La pantalla de Mercados muestra **métricas agregadas por zona** (ciudad/distrito/barrio), no datos de un inmueble individual.

En cada zona, los principales valores se calculan como medias o conteos sobre los inmuebles de esa zona:

- `avgSalePriceSqm`: media de `price / area_m2` (inmuebles de venta)
- `avgRentPriceSqm`: media de `monthly_rent / area_m2` (inmuebles de alquiler)
- `avgMonthlyRent`: media de `monthly_rent` (alquiler)
- `estimatedYield`: media de `yield_gross`
- `investmentScore`: media de `investment_score`
- `analyzedProperties`: número de inmuebles analizados en la zona
- `opportunities`: número de inmuebles que superan umbrales de score/yield

Comportamiento con snapshot:

- **Sin snapshot**: se siguen mostrando zonas y KPI actuales (agregados en tiempo real desde `properties`), pero el histórico mensual (`history`) puede ir vacío.
- **Con snapshot**: además del estado actual, se rellena el histórico temporal de cada zona desde `market_zone_snapshots`.

---

## Comandos de Python (invesprofitpy)

### Scraping + subida raw

```bash
# Ejecutar flujo completo de Madrid (scraping + subida raw)
bash FOTOCASA_COMMANDS.sh

# Solo scraping de venta, 2 páginas, modo raw, sin imágenes
python3 scripts/scrape_listings.py \
  "https://www.fotocasa.es/es/comprar/viviendas/madrid-capital/todas-las-zonas/l" \
  --portal fotocasa --pages 2 --raw-output \
  --no-download-images --no-incremental \
  --output data/fotocasa_madrid_sale_raw.json

# Solo subida de datos raw ya scrapeados (con imágenes)
python3 scripts/upload_raw_properties.py data/fotocasa_madrid_sale_raw.json \
  --api-base-url "http://invesprofitapi.test/api/raw-properties"

# Subida de alquiler
python3 scripts/upload_raw_properties.py data/fotocasa_madrid_rent_raw.json \
  --api-base-url "http://invesprofitapi.test/api/raw-properties" \
  --listing-mode rent
```

---

## Variables de entorno relevantes (invesprofitpy/.env)

| Variable | Descripción |
|---|---|
| `INVESPROFIT_EMAIL` | Email del usuario API para login automático |
| `INVESPROFIT_PASSWORD` | Password del usuario API |
| `INVESPROFIT_API_TOKEN` | Token fijo (alternativa a email/password) |
| `API_RAW_BASE_URL` | Base URL de los endpoints raw (default: `http://invesprofitapi.test/api/raw-properties`) |
| `PAGES` | Páginas a scrapear (default: 2) |
| `DETAIL_DRIVER` | Driver de detalle: `selenium` o `requests` (default: `selenium`) |
| `DOWNLOAD_IMAGES` | Descargar imágenes: `1` o `0` (default: `1`) |
| `SELENIUM_HEADLESS` | Chrome headless: `1` o `0` (default: `0`, ventana visible) |
