import { createApp } from 'vue'
import { createPinia } from 'pinia'

import App from './App.vue'
import router from './router'

// Globale Styles setzen
document.documentElement.style.backgroundColor = '#EDEDED'
document.documentElement.style.fontFamily = 'Nunito, sans-serif'
document.body.style.backgroundColor = '#EDEDED'
document.body.style.margin = '0'
document.body.style.padding = '0'
document.body.style.fontFamily = 'Nunito, sans-serif'

const app = createApp(App)

app.use(createPinia())
app.use(router)

app.mount('#app')
