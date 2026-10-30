function classicStartButton(page) {
  return page.locator("#startStopBtn");
}

function stabilityStartButton(page) {
  return page.locator("#startBtn");
}

module.exports = {
  classicStartButton,
  stabilityStartButton
};
