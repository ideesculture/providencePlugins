# statisticsViewer

Reporting plugin for [Providence (CollectiveAccess)](https://collectiveaccess.org).

Runs a set of **pre-stored SQL queries**, declared in XML, and renders each one
as a table with an optional chart. Curators pick a report from a menu; they
never write SQL themselves. Results can be exported to CSV.

Reports are grouped into *universes* — one XML file per universe — so a single
install can expose several unrelated sets of statistics side by side.

## Install

1. Copy or symlink this folder into `app/plugins/statisticsViewer/` of your
   Providence install.
2. Edit `conf/statisticsViewer.conf`:
   - `enabled` must be `1`;
   - `AvailableUniversesForStats` lists the universes to expose, each one
     matching a file in `XmlStatisticsRootDirectory` (default `xml/SqlRequests`);
   - `ChartingLib` selects the charting backend (`GoogleChartTools`), and
     `ChartTypes` declares the available chart types.
3. Grant the plugin's action to the roles that should see it
   (Manage → Access control → Roles).

## Declaring a report

Each universe is an XML file under `xml/SqlRequests/`. A report is a
`<statistic>` inside a `<statistics_group>`:

| tag                 | meaning                                                   |
|---------------------|-----------------------------------------------------------|
| `id`                | unique identifier within the universe                      |
| `title`             | shown in the menu and as the report heading                |
| `comment`           | explanatory text displayed above the results               |
| `sql`               | the query; column aliases become the table headers         |
| `columns`           | ordered list of columns to display                         |
| `total_columns`     | columns to sum in a totals row                             |
| `charting`          | chart type, taken from `ChartTypes` in the conf            |
| `charting_columns`  | the two columns feeding the chart (label, value)           |

The shipped `archivesbelges_*.xml` files are working examples: objects, loans,
places, representations and entities.

## Per-client views

`views/` holds the generic rendering templates. Client-specific reports live in
a subdirectory — `views/ensad/` ships the ENSAD Matériauthèque reports
(catalogued records, contributions, list of contributions) and shows how to add
your own without touching the generic templates.

## Caveat

The queries are executed as written, against the Providence database, with the
privileges of the application's database user. Treat every XML file as code:
anyone able to add one can run arbitrary SQL. Keep the directory writable only
by administrators.

## Origin

Carried over from the ENSAD Matériauthèque instance (Providence 1.7.17) in
November 2026. An abandoned parallel rewrite that lived in `sr/lib.new` on that
instance — a second charting backend based on pChart and Highcharts — was not
carried over.
