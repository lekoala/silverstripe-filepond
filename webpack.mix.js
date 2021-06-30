const mix = require('laravel-mix');

mix.setPublicPath('client/dist');
mix.setResourceRoot('../');

mix.sass('client/src/styles/FilePondField.scss', 'styles');
mix.js('client/src/js/FilePondField.js', 'js');
