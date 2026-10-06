import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    normalize, isActive, visibleSections, sectionHasActive,
    sectionBadge, flattenForSearch, searchItems,
} from '../../resources/js/lib/navigation.js';

test('normalize unifies Arabic letter variants and strips marks', () => {
    assert.equal(normalize('الإعدادات'), 'الاعدادات');
    assert.equal(normalize('أآإٱ'), 'اااا');
    assert.equal(normalize('مدرسة'), 'مدرسه');
    assert.equal(normalize('مستوى'), 'مستوي');
    assert.equal(normalize('مُدَرِّب'), 'مدرب');
    assert.equal(normalize('تـــهيئة'), 'تهيئه');
});

test('normalize lowercases and strips Latin accents', () => {
    assert.equal(normalize('  Paramétrage '), 'parametrage');
    assert.equal(normalize(null), '');
});

test('isActive honours match, exact and prefix', () => {
    const users = { prefix: '/users', match: /^\/users(?!\/(\d+\/)?activity)/ };
    assert.equal(isActive(users, '/users/5/edit'), true);
    assert.equal(isActive(users, '/users/activity'), false);

    const hub = { prefix: '/board', exact: true };
    assert.equal(isActive(hub, '/board'), true);
    assert.equal(isActive(hub, '/board?year=2026'), true);
    assert.equal(isActive(hub, '/board/tasks'), false);

    assert.equal(isActive({ prefix: '/players' }, '/players/3'), true);
    assert.equal(isActive({ prefix: '/players' }, undefined), false);
});

const raw = [
    { key: 'dashboard', standalone: true, items: [{ label: 'Dashboard', prefix: '/dashboard', always: true }] },
    { key: 'config', label: 'App Configuration', items: [
        { label: 'Categories', prefix: '/categories', module: 'categories' },
        { label: 'Board roles', prefix: '/board-roles', module: 'board' },
    ] },
    { key: 'system', label: 'System', items: [
        { label: 'Users', prefix: '/users', module: 'users', badge: 3 },
        { label: 'Roles', prefix: '/roles', superadminOnly: true },
        { label: 'Backup', prefix: '/backups', superadminOnly: true, desktopOnly: true },
    ] },
];

test('visibleSections keeps only permitted items and drops empty sections', () => {
    const can = (module) => module === 'board';
    const out = visibleSections(raw, { can, isSuperadmin: false, isDesktop: false });
    assert.deepEqual(out.map((s) => s.key), ['dashboard', 'config']);
    assert.deepEqual(out[1].items.map((i) => i.label), ['Board roles']);
});

test('visibleSections: superadmin sees superadminOnly items, desktopOnly needs desktop', () => {
    const can = () => true;
    const web = visibleSections(raw, { can, isSuperadmin: true, isDesktop: false });
    assert.deepEqual(web[2].items.map((i) => i.label), ['Users', 'Roles']);
    const desk = visibleSections(raw, { can, isSuperadmin: true, isDesktop: true });
    assert.deepEqual(desk[2].items.map((i) => i.label), ['Users', 'Roles', 'Backup']);
});

test('sectionHasActive and sectionBadge', () => {
    assert.equal(sectionHasActive(raw[2], '/roles'), true);
    assert.equal(sectionHasActive(raw[2], '/players'), false);
    assert.equal(sectionBadge(raw[2]), 3);
    assert.equal(sectionBadge(raw[1]), 0);
});

test('search matches label or section, accent- and hamza-insensitive', () => {
    const sections = [
        { key: 'config', label: 'تهيئة التطبيق', items: [{ label: 'الإعدادات', href: '/settings' }] },
        { key: 'cfg-fr', label: 'Paramétrage', items: [{ label: 'Catégories', href: '/categories' }] },
    ];
    const entries = flattenForSearch(sections);
    assert.equal(entries[0].section, 'تهيئة التطبيق');
    assert.deepEqual(searchItems(entries, 'اعدادات').map((e) => e.href), ['/settings']);
    assert.deepEqual(searchItems(entries, 'parametrage').map((e) => e.href), ['/categories']);
    assert.deepEqual(searchItems(entries, 'CATEG').map((e) => e.href), ['/categories']);
    assert.equal(searchItems(entries, '   ').length, 2);
    assert.equal(searchItems(entries, 'zzz').length, 0);
});

test('flattenForSearch gives standalone items a null section', () => {
    const entries = flattenForSearch([raw[0]]);
    assert.equal(entries[0].section, null);
    assert.equal(entries[0].haystack, 'dashboard');
});
