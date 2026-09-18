import { createApp } from 'vue';
import App from './components/App.vue';
import { responsiveTable } from './directives/responsiveTable';
import { responsiveTabs } from './directives/responsiveTabs';
import './styles/panel-mobile.css';

createApp(App).directive('responsive-table', responsiveTable).directive('responsive-tabs', responsiveTabs).mount('#app');
