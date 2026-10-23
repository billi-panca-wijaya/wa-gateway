import Axios from 'axios';

/** Same-origin API client. Keep gateway keys on the Laravel server. */
const axios = Axios.create({
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    withCredentials: true,
    withXSRFToken: true,
});

export default axios;
