import { createApp } from 'vue'
import '@fontsource-variable/inter'
import './styles/tokens.css'
import './styles/base.css'
import App from './App.vue'
import router from './router.js'

createApp(App).use(router).mount('#app')
