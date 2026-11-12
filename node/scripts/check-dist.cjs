// Vérifie le build CommonJS : require par le nom du package.
const assert = require('node:assert/strict');
const core = require('@labyrinthe/labpay');

assert.equal(typeof core.LabPay, 'function');
assert.equal(typeof core.Webhooks.constructEvent, 'function');
assert.ok(new core.ApiError('x', 'HTTP_ERROR', 500) instanceof core.LabPayError);
console.log(`CJS OK (${core.VERSION})`);
