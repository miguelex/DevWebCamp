const { src, dest, watch, parallel, series } = require('gulp');
const sass = require('gulp-sass')(require('sass'));
const autoprefixer = require('autoprefixer');
const postcss = require('gulp-postcss');
const sourcemaps = require('gulp-sourcemaps');
const webpack = require('webpack-stream');

const paths = {
    scssEntry: 'src/scss/app.scss',
    scss: 'src/scss/**/*.scss',
    jsEntry: 'src/js/app.js',
    js: 'src/js/**/*.js',
    images: 'src/img/**/*'
};

function css() {
    return src(paths.scssEntry)
        .pipe(sourcemaps.init())
        .pipe(sass({ outputStyle: 'expanded' }))
        .pipe(postcss([autoprefixer()]))
        .pipe(sourcemaps.write('.'))
        .pipe(dest('public/build/css'));
}

function javascript() {
    return src(paths.jsEntry)
        .pipe(webpack({
            mode: 'production',
            devtool: 'source-map',
            entry: './src/js/app.js',
            output: { filename: 'main.min.js' },
            module: {
                rules: [{
                    test: /\.css$/i,
                    use: ['style-loader', 'css-loader']
                }]
            }
        }))
        .pipe(dest('public/build/js'));
}

function images() {
    return src(paths.images, { encoding: false })
        .pipe(dest('public/build/img'));
}

const build = parallel(css, javascript, images);

function watchFiles(done) {
    watch(paths.scss, css);
    watch(paths.js, javascript);
    watch(paths.images, images);
    done();
}

exports.css = css;
exports.js = javascript;
exports.imagenes = images;
exports.build = build;
exports.dev = series(build, watchFiles);
