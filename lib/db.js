require('dotenv').config({ quiet: true });
const { Pool, types } = require('pg');
types.setTypeParser(1082, (v) => v);
types.setTypeParser(1114, (v) => v);
const pool = new Pool(
  process.env.DATABASE_URL
    ? { connectionString: process.env.DATABASE_URL }
    : {
        user: process.env.PGUSER || process.env.DB_USER,
        host: process.env.PGHOST || process.env.DB_HOST || 'localhost',
        password: process.env.PGPASSWORD || process.env.DB_PASSWORD,
        port: Number(process.env.PGPORT || process.env.DB_PORT || 5432),
        database: process.env.PGDATABASE || process.env.DB_NAME || 'trading_journal',
        connectionTimeoutMillis: 5000,
      },
);
module.exports = pool;
