// Magnifier bubble for help screenshots: a square showing the target box (plus
// a margin) cut from the same image with background-size/position. Pure, so
// `node --test` loads it directly.
//
// box: percentages of the screenshot — x, w of its width; y, h of its height.
// aspect: screenshot width / height.

const round = (n) => Math.round(n * 100) / 100;
const clamp = (n, lo, hi) => Math.min(hi, Math.max(lo, n));

export function zoomStyle(box, aspect = 1.6, margin = 0.6) {
    // Compare width and height in the same unit: % of the image width.
    const side = Math.max(box.w, box.h / aspect) * (1 + 2 * margin);
    const regionW = clamp(side, 8, 100);
    const regionH = Math.min(100, regionW * aspect); // % of the height, square on screen

    const cx = box.x + box.w / 2;
    const cy = box.y + box.h / 2;
    const regionX = clamp(cx - regionW / 2, 0, 100 - regionW);
    const regionY = clamp(cy - regionH / 2, 0, 100 - regionH);

    // CSS: position p% puts the image's p% point on the bubble's p% point.
    const posX = regionW >= 100 ? 0 : (regionX / (100 - regionW)) * 100;
    const posY = regionH >= 100 ? 0 : (regionY / (100 - regionH)) * 100;

    return {
        backgroundSize: `${round(10000 / regionW)}% auto`,
        backgroundPosition: `${round(posX)}% ${round(posY)}%`,
    };
}

export function bubbleSide(box) {
    return box.x + box.w / 2 >= 50 ? 'left' : 'right';
}
