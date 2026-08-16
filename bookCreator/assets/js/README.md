# Third-party JavaScript

## paged.polyfill.js — Paged.js 0.4.3

CSS Paged Media polyfill, used by the preview only. It does in the browser what
WeasyPrint does on the server: split the flow into pages, place folios and
running heads from the same `@page` rules.

- Upstream: https://gitlab.coko.foundation/pagedjs/pagedjs
- Licence: MIT
- Retrieved from https://unpkg.com/pagedjs@0.4.3/dist/paged.polyfill.js

Bundled here rather than loaded from a CDN, on purpose. The preview has to keep
working on an installation without outbound network access, and a CDN that goes
away takes the pagination with it — which is precisely what happened to the
fonts of the v1 stylesheet, still pointing at a host that has been dead for
years.

The PDF chain never loads this file: WeasyPrint implements Paged Media natively.
It is added to the document by `BookHtmlBuilder` only when the `preview` option
is set, so the file sent to the renderer stays byte-comparable to the one shown
in the browser, minus this single script tag.

### Updating

Replace the file, update the version above, and re-run the visual check on a
chapter: a polyfill upgrade can change where a page breaks, which is exactly
what the preview is meant to predict.
