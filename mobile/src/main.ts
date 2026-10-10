import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import 'vant/lib/index.css'
import './assets/theme.css'
import {
  Button,
  Cell,
  CellGroup,
  Empty,
  Field,
  NavBar,
  NoticeBar,
  Progress,
  SwipeCell,
  Tab,
  Tabbar,
  TabbarItem,
  Tabs,
  Tag,
} from 'vant'

const app = createApp(App)
app.use(createPinia())
app.use(router)
for (const component of [
  Button, Cell, CellGroup, Empty, Field, NavBar, NoticeBar, Progress, SwipeCell,
  Tab, Tabbar, TabbarItem, Tabs, Tag,
]) {
  app.use(component)
}
app.mount('#app')
