const pool = require('../lib/db');
pool
  .query(require('fs').readFileSync(require('path').join(__dirname, '../schema.sql'), 'utf8'))
  .then(() => console.log('Database migration complete.'))
  .catch((e) => {
    console.error(e.message);
    process.exitCode = 1;
  })
  .finally(() => pool.end());
