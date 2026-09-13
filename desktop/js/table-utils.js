/* Shared pure operations for PluginScope tables; no DOM and no network. */
(function (root) {
    'use strict';
    const collator = new Intl.Collator(undefined, {numeric: true, sensitivity: 'base'});
    const api = {
        networkShares(local, internet) {
            const total = local + internet;
            if (!total) return {local:null, internet:null};
            const share = Math.round(local / total * 1000) / 10;
            return {local:share, internet:Math.round((100-share)*10)/10};
        },
        matchesStatus(enabled, filter) {
            return filter === 'enabled' ? enabled === true : filter === 'disabled' ? enabled === false : true;
        },
        sortRows(rows, key, descending) {
            return rows.slice().sort((a, b) => {
                const left = a[key], right = b[key];
                // Missing values remain last in both directions.
                if (left === null || left === undefined) return right == null ? 0 : 1;
                if (right === null || right === undefined) return -1;
                const order = typeof left === 'number' && typeof right === 'number'
                    ? left - right : collator.compare(String(left), String(right));
                return order * (descending ? -1 : 1);
            });
        },
        endpoint(address, port) {
            return (address.includes(':') ? '[' + address + ']' : address) + ':' + port;
        }
    };
    if (typeof module === 'object' && module.exports) module.exports = api;
    else root.PluginScopeTables = api;
})(typeof window === 'object' ? window : globalThis);
