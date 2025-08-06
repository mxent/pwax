import '/node_modules/vue/dist/vue.global.prod.js';
import '/node_modules/vue-router/dist/vue-router.global.prod.js';
import '/node_modules/pinia/dist/pinia.iife.prod.js';
import '/node_modules/dexie/dist/dexie.min.js';

const pwaxHeaders = new Headers();
pwaxHeaders.append("Accept", "application/json");
pwaxHeaders.append("X-Requested-With", "XMLHttpRequest");
pwaxHeaders.append("X-Pwa-Component", "true");
window.pwaxHeaders = pwaxHeaders;
window.pwaxFetch = async function (url, options = {}) {
    const f = await fetch(url, options);
    const j = await f.json();
    const s = j.script ? await import(`data:text/javascript;base64,${btoa(j.script)}`) : {};
    const e = s?.default || {};
    const v = j.template ? { template: j.template, ...e } : e;
    return {
        s: s,
        v: v
    };
};
window.pwaxImports = {};
window.pwaxImport = async function (url, name, key = '') {
    return window.pwaxImports[name] = window.pwaxImports[name] || await (async function() {
        var r = await window.pwaxFetch(url, {
            headers: window.pwaxHeaders
        });
        return key.length ? r.s[key] : r.v;
    })();
};