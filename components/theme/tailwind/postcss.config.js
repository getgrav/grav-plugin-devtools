module.exports = {
    plugins: {
        'postcss-import': {},
        'precss': {},
        'tailwindcss': {},
        'postcss-nested': {},
        'autoprefixer': {},
        ...process.env.NODE_ENV === 'production'
            ? {'cssnano': {}} : {}
    },
}
