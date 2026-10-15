import { test } from 'node:test';
import assert from 'node:assert/strict';
import { paymentStatusColor } from '../../resources/js/lib/statusLabels.js';

test('payment status colours are the same for any casing', () => {
    assert.equal(paymentStatusColor('paid'), 'emerald');
    assert.equal(paymentStatusColor('Paid'), 'emerald');
    assert.equal(paymentStatusColor('PARTIAL'), 'amber');
    assert.equal(paymentStatusColor('Exempt'), 'slate');
});

test('unpaid, unknown and empty statuses read as rose', () => {
    for (const code of ['unpaid', 'Unpaid', 'whatever', '', null, undefined]) {
        assert.equal(paymentStatusColor(code), 'rose');
    }
});
