import * as bootstrap from 'bootstrap';
import axios from 'axios';

window.bootstrap = bootstrap;

window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
