const pool = require('../lib/db');
require('../lib/email')
  .sendWeekly(pool)
  .then(console.log)
  .catch((e) => {
    console.error(e.message);
    process.exitCode = 1;
  })
  .finally(() => pool.end());
