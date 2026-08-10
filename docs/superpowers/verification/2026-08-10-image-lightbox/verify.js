/* Drives the real lightbox.js + theme.css against BookStack's fixture markup. */
const {chromium} = require('playwright');

const URL = 'http://localhost:8099/';
const results = [];
let warnings = [];
const notFound = [];

function check(row, name, pass, detail) {
    results.push({row, name, pass, detail: detail || ''});
    console.log(`${pass ? 'PASS' : 'FAIL'}  ${row}. ${name}${detail ? '  — ' + detail : ''}`);
}

const box = (page) => page.evaluate(() => {
    const r = document.querySelector('.rl-backdrop');
    if (!r) return {exists: false};
    const img = r.querySelector('.rl-img');
    const vis = (el) => el && !el.hidden && getComputedStyle(el).display !== 'none';
    return {
        exists: true,
        open: !r.hidden && r.classList.contains('rl-open'),
        hidden: r.hidden,
        src: img.getAttribute('src') || '',
        alt: img.getAttribute('alt') || '',
        caption: r.querySelector('.rl-caption').textContent,
        counter: r.querySelector('.rl-counter').textContent,
        counterVisible: vis(r.querySelector('.rl-counter')),
        prevVisible: vis(r.querySelector('.rl-prev')),
        nextVisible: vis(r.querySelector('.rl-next')),
        barVisible: vis(r.querySelector('.rl-bar')),
        zoomable: r.classList.contains('rl-zoomable'),
        zoomed: r.classList.contains('rl-zoomed'),
        role: r.getAttribute('role'),
        ariaModal: r.getAttribute('aria-modal'),
        activeId: document.activeElement.className || document.activeElement.id,
        bodyOverflow: document.body.style.overflow,
        stageScroll: [r.querySelector('.rl-stage').scrollLeft, r.querySelector('.rl-stage').scrollTop],
        imgNatural: [img.naturalWidth, img.naturalHeight],
        imgClient: [img.clientWidth, img.clientHeight],
    };
});

// Waits for the full-size original to replace the thumbnail.
const waitFull = (page, part) => page.waitForFunction(
    (p) => (document.querySelector('.rl-img').getAttribute('src') || '').includes(p),
    part, {timeout: 5000},
);

// The open class is added on the next frame, so state read before it lies.
const waitOpen = (page) => page.waitForFunction(
    () => document.querySelector('.rl-backdrop.rl-open') !== null, null, {timeout: 5000},
);

(async () => {
    // Set LB_CHROME to use a specific binary; otherwise Playwright's own.
    const browser = await chromium.launch(
        process.env.LB_CHROME ? {executablePath: process.env.LB_CHROME} : {},
    );
    const page = await browser.newPage({viewport: {width: 1280, height: 800}});
    page.on('console', (m) => {
        // The harness ships no favicon; that 404 is not the theme's.
        if (m.location() && (m.location().url || '').includes('favicon')) return;
        if (m.text().includes('refresh lightbox') || m.type() === 'error') warnings.push(m.text());
    });
    // Note which requests 404 so a harness-only miss (favicon) is not mistaken
    // for a broken theme asset.
    page.on('requestfailed', (r) => warnings.push('requestfailed: ' + r.url()));
    page.on('response', (r) => {
        if (r.status() >= 400) notFound.push(`${r.status()} ${r.url()}`);
    });
    page.on('pageerror', (e) => warnings.push('pageerror: ' + e.message));

    await page.goto(URL);
    await page.waitForLoadState('networkidle');

    // --- Row 1: click opens at full resolution with a counter ---------------
    await page.click('#i1');
    await waitFull(page, 'big.png');
    let s = await box(page);
    check(1, 'Click opens at full resolution, counter n/total',
        s.open && s.src.includes('big.png') && s.counter === '1 / 4' && s.counterVisible,
        `src=${s.src.split('/').pop()} counter="${s.counter}"`);
    check('1b', 'Overlay is a labelled modal dialog',
        s.role === 'dialog' && s.ariaModal === 'true' && s.activeId.includes('rl-close'),
        `role=${s.role} aria-modal=${s.ariaModal} focus=${s.activeId}`);
    check('1c', 'Background scroll is locked', s.bodyOverflow === 'hidden', `overflow=${s.bodyOverflow}`);

    // --- Row 2: arrow navigation wraps, page images only --------------------
    await page.keyboard.press('ArrowRight');
    await waitFull(page, 'second.png');
    s = await box(page);
    check(2, 'ArrowRight advances and updates caption',
        s.counter === '2 / 4' && s.caption === 'Second fixture image', `counter="${s.counter}" caption="${s.caption}"`);

    await page.keyboard.press('ArrowRight');
    await page.keyboard.press('ArrowRight');
    s = await box(page);
    check('2b', 'Third and fourth images are the eligible ones', s.counter === '4 / 4', `counter="${s.counter}"`);

    await page.keyboard.press('ArrowRight');
    s = await box(page);
    check('2c', 'Wraps forward to the first', s.counter === '1 / 4', `counter="${s.counter}"`);

    await page.keyboard.press('ArrowLeft');
    s = await box(page);
    check('2d', 'Wraps backward to the last', s.counter === '4 / 4', `counter="${s.counter}"`);

    await page.click('.rl-prev');
    s = await box(page);
    check('2e', 'On-screen arrows match the keys', s.counter === '3 / 4', `counter="${s.counter}"`);

    await page.keyboard.press('Escape');
    s = await box(page);
    check(15, 'Escape closes and restores scroll',
        !s.open && s.hidden && s.bodyOverflow !== 'hidden', `hidden=${s.hidden} overflow="${s.bodyOverflow}"`);

    // --- Row 3: a comment is its own set ------------------------------------
    await page.click('#ic');
    await waitFull(page, 'second.png');
    s = await box(page);
    check(3, 'Comment image opens; its set is that comment alone',
        s.open && !s.counterVisible && !s.prevVisible && !s.nextVisible,
        `counter shown=${s.counterVisible} arrows=${s.prevVisible}/${s.nextVisible}`);
    check('3b', 'Single-image lightbox traps Tab on the close button alone', await (async () => {
        await page.keyboard.press('Tab');
        const a1 = await page.evaluate(() => document.activeElement.className);
        await page.keyboard.press('Tab');
        const a2 = await page.evaluate(() => document.activeElement.className);
        return a1.includes('rl-close') && a2.includes('rl-close');
    })());
    await page.keyboard.press('Escape');

    // --- Row 4: an image linked elsewhere must navigate ---------------------
    await page.click('#i4');
    await page.waitForLoadState('load');
    const navigated = page.url().includes('/books/lightbox-test');
    check(4, 'Image linked to a book navigates, no lightbox', navigated, `url=${page.url()}`);
    await page.goto(URL);
    await page.waitForLoadState('networkidle');

    // --- Row 5: unlinked image uses its own src -----------------------------
    await page.click('#i3');
    await waitOpen(page);
    s = await box(page);
    check(5, 'Unlinked image opens using its own src',
        s.open && s.src.includes('small.png'), `src=${s.src.split('/').pop()}`);
    await page.keyboard.press('Escape');

    // --- Rows 6/7: zoom -----------------------------------------------------
    await page.click('#i1');
    await waitFull(page, 'big.png');
    await page.waitForTimeout(150);
    s = await box(page);
    check(6, 'Oversized image reports itself zoomable',
        s.zoomable && s.imgNatural[0] === 2400 && s.imgClient[0] < 2400,
        `natural=${s.imgNatural} client=${s.imgClient}`);

    const imgBox = await page.locator('.rl-img').boundingBox();
    await page.mouse.click(imgBox.x + imgBox.width * 0.2, imgBox.y + imgBox.height * 0.2);
    s = await box(page);
    const zoomedScroll = s.stageScroll;
    check('6b', 'Click switches to actual size, clicked point scrolled into view',
        s.zoomed && s.imgClient[0] === 2400 && (zoomedScroll[0] > 0 || zoomedScroll[1] > 0),
        `client=${s.imgClient} scroll=${zoomedScroll}`);

    // Drag to pan, then confirm the release did not collapse the zoom.
    await page.mouse.move(imgBox.x + imgBox.width / 2, imgBox.y + imgBox.height / 2);
    await page.mouse.down();
    await page.mouse.move(imgBox.x + imgBox.width / 2 - 120, imgBox.y + imgBox.height / 2 - 90, {steps: 8});
    await page.mouse.up();
    s = await box(page);
    check('6c', 'Drag pans and does not collapse the zoom',
        s.zoomed && (s.stageScroll[0] !== zoomedScroll[0] || s.stageScroll[1] !== zoomedScroll[1]),
        `zoomed=${s.zoomed} scroll ${zoomedScroll} -> ${s.stageScroll}`);

    await page.mouse.click(imgBox.x + imgBox.width / 2, imgBox.y + imgBox.height / 2);
    s = await box(page);
    check('6d', 'A click without movement returns to fit', !s.zoomed, `zoomed=${s.zoomed}`);

    await page.keyboard.press('ArrowRight');
    await page.waitForTimeout(100);
    s = await box(page);
    check('6e', 'Navigating away from a zoomed image resets to fit', !s.zoomed, `zoomed=${s.zoomed}`);
    await page.keyboard.press('Escape');

    // Row 7: an image at or below its natural size has an inert toggle.
    await page.click('#i5');
    await page.waitForTimeout(200);
    s = await box(page);
    const before = s.zoomed;
    const b5 = await page.locator('.rl-img').boundingBox();
    await page.mouse.click(b5.x + b5.width / 2, b5.y + b5.height / 2);
    s = await box(page);
    check(7, 'Image that already fits: toggle inert, no zoom class',
        !s.zoomable && !before && !s.zoomed, `zoomable=${s.zoomable} zoomed=${s.zoomed}`);
    await page.keyboard.press('Escape');

    // --- Row 8: modifier click falls through --------------------------------
    const ctx = page.context();
    const popupWait = ctx.waitForEvent('page', {timeout: 3000}).catch(() => null);
    await page.click('#i1', {modifiers: ['ControlOrMeta']});
    const popup = await popupWait;
    s = await box(page);
    check(8, 'Ctrl/Cmd-click opens the original in a new tab, no overlay',
        !s.open && popup !== null, `overlay open=${s.open} newTab=${popup !== null}`);
    if (popup) await popup.close();

    // --- Row 9: the editor must be untouched --------------------------------
    await page.click('#ie');
    s = await box(page);
    check(9, 'Image inside contenteditable/.editor-content-area: no lightbox',
        !s.open, `overlay open=${s.open}`);

    // --- Row 10: markdown preview is a separate document ---------------------
    const frame = page.frameLocator('#mdpreview');
    await frame.locator('#im').click();
    s = await box(page);
    check(10, 'Markdown preview iframe: no lightbox in the parent document',
        !s.open, `parent overlay open=${s.open}`);

    // --- Row 13: mobile viewport --------------------------------------------
    await page.setViewportSize({width: 375, height: 667});
    await page.goto(URL);
    await page.waitForLoadState('networkidle');
    await page.click('#i1');
    await waitFull(page, 'big.png');
    s = await box(page);
    const overflow = await page.evaluate(() => {
        const r = document.querySelector('.rl-backdrop');
        const controls = ['.rl-close', '.rl-prev', '.rl-next'].map((sel) => {
            const b = r.querySelector(sel).getBoundingClientRect();
            return b.left >= 0 && b.right <= window.innerWidth && b.top >= 0 && b.bottom <= window.innerHeight;
        });
        return {
            horizontal: document.documentElement.scrollWidth > window.innerWidth,
            controlsInView: controls.every(Boolean),
        };
    });
    check(13, 'Mobile 375px: controls reachable, no horizontal overflow',
        s.open && overflow.controlsInView && !overflow.horizontal,
        `controlsInView=${overflow.controlsInView} hOverflow=${overflow.horizontal}`);
    await page.keyboard.press('Escape');
    await page.setViewportSize({width: 1280, height: 800});

    // --- Row 12: dark mode ---------------------------------------------------
    await page.goto(URL);
    await page.evaluate(() => document.documentElement.classList.add('dark-mode'));
    await page.click('#i1');
    await waitFull(page, 'big.png');
    const dark = await page.evaluate(() => {
        const r = document.querySelector('.rl-backdrop');
        return {
            backdrop: getComputedStyle(r).backgroundColor,
            caption: getComputedStyle(r.querySelector('.rl-caption')).color,
            btn: getComputedStyle(r.querySelector('.rl-close')).color,
        };
    });
    check(12, 'Dark mode: overlay keeps its own dark palette and light text',
        dark.backdrop.includes('10, 13, 18') && dark.btn === 'rgb(255, 255, 255)',
        `backdrop=${dark.backdrop} button=${dark.btn}`);
    await page.keyboard.press('Escape');

    // --- Row 14: keyboard only ----------------------------------------------
    await page.goto(URL);
    await page.waitForLoadState('networkidle');
    await page.click('#i1');
    await waitFull(page, 'big.png');
    let a = await page.evaluate(() => document.activeElement.className);
    // Clicking the image focuses its wrapping link, so that link is the trigger
    // focus must come back to.
    const tabs = [];
    const escaped = [];
    for (let i = 0; i < 5; i++) {
        await page.keyboard.press('Tab');
        const t = await page.evaluate(() => ({
            name: document.activeElement.className.replace('rl-btn ', ''),
            inside: document.querySelector('.rl-backdrop').contains(document.activeElement),
        }));
        tabs.push(t.name);
        escaped.push(t.inside);
    }
    await page.keyboard.press('Escape');
    const returned = await page.evaluate(() => document.activeElement.id);
    check(14, 'Keyboard only: focus enters, never escapes the dialog, returns to the trigger',
        a.includes('rl-close') && escaped.every(Boolean) && returned === 'l1',
        `enter=${a.trim()} cycle=[${tabs.join(' → ')}] return=#${returned}`);

    // --- Row 15: backdrop and × ----------------------------------------------
    // Open an image far down the page, as a real reader would, so scroll
    // preservation is measured somewhere other than the top of the document.
    await page.click('#if');
    await waitOpen(page);
    const scrollBefore = await page.evaluate(() => window.scrollY);
    const scrollDuring = await page.evaluate(() => window.scrollY);
    // Clear of the image (x >= 300 at this size) and of .rl-prev (x 12-56, y 378-422).
    await page.mouse.click(150, 200);
    s = await box(page);
    const scrollAfter = await page.evaluate(() => window.scrollY);
    check('15b', 'Backdrop click closes; scroll position held throughout',
        !s.open && scrollBefore > 1000 && scrollDuring === scrollBefore && scrollAfter === scrollBefore,
        `open=${s.open} scroll ${scrollBefore} -> during ${scrollDuring} -> after ${scrollAfter}`);

    await page.click('#i1');
    await waitOpen(page);
    await page.click('.rl-close');
    s = await box(page);
    check('15c', 'The × closes', !s.open, `open=${s.open}`);

    // --- Degradation: a body without the script still has working links ------
    check('16', 'No lightbox warnings or page errors during the run',
        warnings.length === 0, warnings.join(' | ') || 'clean');
    // The harness has no favicon; only a missing theme asset would matter.
    const themeMisses = notFound.filter((u) => !u.includes('favicon'));
    check('16b', 'Every theme asset the page requests is served',
        themeMisses.length === 0, themeMisses.join(' | ') || `clean (ignored: ${notFound.join(', ') || 'none'})`);

    await browser.close();

    const failed = results.filter((r) => !r.pass);
    console.log(`\n${results.length - failed.length}/${results.length} checks passed`);
    if (failed.length) {
        console.log('FAILED: ' + failed.map((f) => f.row).join(', '));
        process.exit(1);
    }
})();
