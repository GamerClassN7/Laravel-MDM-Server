# OUI vendor data

Vendor names of the first bytes of a MAC address (IEEE MA-L, MA-M and MA-S registries), one file per
first byte (`AA.json`: `{"BBCC": "Vendor", ...}`, the key is the rest of the prefix: 4, 5 or 7
characters). Read by `App\Support\MacVendor`.

Generated from the npm package [`oui-data`](https://www.npmjs.com/package/oui-data) (silverwind, see
`LICENSE`), which converts the IEEE registries: the first line of each entry, entries of the IEEE
Registration Authority itself (the parents of MA-M/MA-S blocks) left out. To refresh, download the
package (`npm pack oui-data`) and write the files again from its `index.json`.
