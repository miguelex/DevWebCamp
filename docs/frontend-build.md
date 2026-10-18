# Frontend build

Use `npm ci` to install the locked dependencies, then `npm run build` to produce the public assets. The build finishes without starting watchers. Use `npm run dev` when editing: it runs the same initial build, then watches SCSS, JavaScript, and static images.

| Source | Public output | Source map |
| --- | --- | --- |
| `src/scss/app.scss` | `public/build/css/app.css` | `app.css.map` |
| `src/js/app.js` | `public/build/js/main.min.js` | `main.min.js.map` |
| `src/img/**/*` | `public/build/img/**/*` | None |

Both build and dev generate the same CSS and JavaScript source maps. Map references in the generated files use those exact names. Image files are copied without optimization, conversion, or renaming; `public/img/speakers/` contains runtime uploads and is not part of this build.

`public/build/` remains versioned for deployment. Treat it as generated output: edit the sources, run `npm run build`, and review the resulting asset changes together. Do not edit generated files by hand. A second build should leave the output bytes unchanged.
