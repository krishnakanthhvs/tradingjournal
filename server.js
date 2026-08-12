const express = require('express');
const session = require('express-session');
const { Pool } = require('pg');
const bcrypt = require('bcryptjs');
const path = require('path');

const app = express();
const PORT = process.env.PORT || 3000;

const pool = new Pool({
  user: 'krishnakanth',
  host: 'localhost',
  database: 'trading_journal',
  password: 'my_password_123', // Your DB Password
  port: 5432,
});

app.use(express.json());
app.use(express.static(path.join(__dirname, 'public')));
app.use(session({
  secret: 'trading-journal-secret-key',
  resave: false,
  saveUninitialized: false
}));

const requireAuth = (req, res, next) => {
  if (!req.session.userId) return res.status(401).json({ error: 'Unauthorized' });
  next();
};

// --- AUTH ROUTES ---
app.post('/api/auth/register', async (req, res) => {
  const { email, password } = req.body;
  try {
    const hash = await bcrypt.hash(password, 10);
    const result = await pool.query(
      'INSERT INTO users (email, password_hash) VALUES ($1, $2) RETURNING id, email',
      [email, hash]
    );
    req.session.userId = result.rows[0].id;
    res.json({ success: true, user: result.rows[0] });
  } catch (err) {
    res.status(400).json({ error: 'User already exists or invalid data.' });
  }
});

app.post('/api/auth/login', async (req, res) => {
  const { email, password } = req.body;
  try {
    const result = await pool.query('SELECT * FROM users WHERE email = $1', [email]);
    if (result.rows.length === 0) return res.status(400).json({ error: 'User not found' });

    const user = result.rows[0];
    const match = await bcrypt.compare(password, user.password_hash);
    if (!match) return res.status(400).json({ error: 'Invalid password' });

    req.session.userId = user.id;
    res.json({ success: true, user: { id: user.id, email: user.email } });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

app.post('/api/auth/logout', (req, res) => {
  req.session.destroy();
  res.json({ success: true });
});

app.get('/api/auth/me', (req, res) => {
  if (req.session.userId) res.json({ loggedIn: true });
  else res.json({ loggedIn: false });
});

// --- CAPITAL MANAGEMENT ---
app.post('/api/capital', requireAuth, async (req, res) => {
  const userId = req.session.userId;
  const { yearMonth, capital } = req.body;

  try {
    const checkRes = await pool.query(
      'SELECT capital FROM monthly_capitals WHERE user_id = $1 AND year_month = $2',
      [userId, yearMonth]
    );

    if (checkRes.rows.length > 0) {
      return res.status(400).json({ error: 'Capital for this month is fixed and cannot be changed.' });
    }

    await pool.query(
      'INSERT INTO monthly_capitals (user_id, year_month, capital) VALUES ($1, $2, $3)',
      [userId, yearMonth, capital]
    );

    res.json({ success: true, capital });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// --- TRADE & DASHBOARD ROUTES ---
app.get('/api/strategies', requireAuth, async (req, res) => {
  const result = await pool.query(
    'SELECT * FROM strategies WHERE user_id = $1 OR user_id IS NULL',
    [req.session.userId]
  );
  res.json(result.rows);
});

// Add Trade with Lot Size, Entry/Exit Times, Market Close Strike & PnL calculation
app.post('/api/trades', requireAuth, async (req, res) => {
  const {
    symbol,
    instrument_type,
    expiry_date,
    entry_price,
    exit_price,
    quantity,
    lot_size,
    strategy_id,
    trade_date,
    entry_time,
    exit_time,
    market_close_strike, // <--- MUST BE DESTRUCTURED HERE
    notes
  } = req.body;

  try {
    const userId = req.session.userId;
    const pnl = (parseFloat(exit_price) - parseFloat(entry_price)) * parseInt(quantity) * parseInt(lot_size || 1);

    const query = `
      INSERT INTO trades (
        user_id, symbol, instrument_type, expiry_date, 
        entry_price, exit_price, quantity, lot_size, 
        pnl, strategy_id, trade_date, entry_time, exit_time, 
        market_close_strike, notes
      ) 
      VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13, $14, $15)
      RETURNING *
    `;

    const values = [
      userId,
      symbol,
      instrument_type,
      expiry_date || null,
      entry_price,
      exit_price,
      quantity,
      lot_size,
      pnl,
      strategy_id || null,
      trade_date,
      entry_time || null,
      exit_time || null,
      market_close_strike ? parseFloat(market_close_strike) : null,
      notes || null
    ];

    const result = await pool.query(query, values);
    res.status(201).json({ success: true, trade: result.rows[0] });
  } catch (err) {
    console.error('Error inserting trade:', err);
    res.status(500).json({ error: 'Database error' });
  }
});
  
// Delete Trade Route
app.delete('/api/trades/:id', requireAuth, async (req, res) => {
  try {
    await pool.query('DELETE FROM trades WHERE id = $1 AND user_id = $2', [req.params.id, req.session.userId]);
    res.json({ success: true });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Dashboard Data Route
app.get('/api/dashboard', requireAuth, async (req, res) => {
  const userId = req.session.userId;
  const yearMonth = req.query.month || new Date().toISOString().slice(0, 7);

  try {
    const capRes = await pool.query(
      'SELECT capital FROM monthly_capitals WHERE user_id = $1 AND year_month = $2',
      [userId, yearMonth]
    );

    const isCapitalSet = capRes.rows.length > 0;
    const monthlyCapital = isCapitalSet ? parseFloat(capRes.rows[0].capital) : 0;

    const monthTradesRes = await pool.query(
      `SELECT t.*, s.name as strategy_name 
       FROM trades t 
       LEFT JOIN strategies s ON t.strategy_id = s.id 
       WHERE t.user_id = $1 AND TO_CHAR(t.trade_date, 'YYYY-MM') = $2 
       ORDER BY t.trade_date DESC`,
      [userId, yearMonth]
    );
    const monthTrades = monthTradesRes.rows;

    const monthPnL = monthTrades.reduce((acc, t) => acc + parseFloat(t.pnl), 0);
    const monthPnLPercent = monthlyCapital > 0 ? ((monthPnL / monthlyCapital) * 100).toFixed(2) : '0.00';

    const todayStr = new Date().toISOString().slice(0, 10);
    const todayRes = await pool.query(
      `SELECT SUM(pnl) as today_pnl FROM trades WHERE user_id = $1 AND DATE(trade_date) = $2`,
      [userId, todayStr]
    );
    const todayPnL = parseFloat(todayRes.rows[0]?.today_pnl || 0);

    let bestTrade = null;
    let worstTrade = null;
    if (monthTrades.length > 0) {
      const sorted = [...monthTrades].sort((a, b) => parseFloat(b.pnl) - parseFloat(a.pnl));
      bestTrade = sorted[0];
      worstTrade = sorted[sorted.length - 1];
    }

    const allTradesRes = await pool.query(
      `SELECT pnl FROM trades WHERE user_id = $1 ORDER BY trade_date ASC`,
      [userId]
    );
    let maxStreak = 0;
    let currentStreak = 0;
    allTradesRes.rows.forEach(t => {
      if (parseFloat(t.pnl) > 0) {
        currentStreak++;
        if (currentStreak > maxStreak) maxStreak = currentStreak;
      } else if (parseFloat(t.pnl) < 0) {
        currentStreak = 0;
      }
    });

    const strategyRes = await pool.query(
      `SELECT s.name, SUM(t.pnl) as total_pnl 
       FROM trades t 
       JOIN strategies s ON t.strategy_id = s.id 
       WHERE t.user_id = $1 
       GROUP BY s.id, s.name 
       ORDER BY total_pnl DESC LIMIT 1`,
      [userId]
    );
    const bestStrategy = strategyRes.rows[0]?.name || 'N/A';

    const year = yearMonth.split('-')[0];
    const yearlyRes = await pool.query(
      `SELECT TO_CHAR(trade_date, 'Mon') as month, SUM(pnl) as pnl, EXTRACT(MONTH FROM trade_date) as m_num
       FROM trades 
       WHERE user_id = $1 AND TO_CHAR(trade_date, 'YYYY') = $2
       GROUP BY month, m_num ORDER BY m_num`,
      [userId, year]
    );

    res.json({
      isCapitalSet,
      monthlyCapital,
      monthPnL,
      monthPnLPercent,
      todayPnL,
      winningStreak: maxStreak,
      bestStrategy,
      bestTrade,
      worstTrade,
      monthTrades,
      yearlyPnL: yearlyRes.rows
    });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

app.post('/api/strategies', requireAuth, async (req, res) => {
  const { name, description } = req.body;
  const userId = req.session.userId;

  if (!name || name.trim() === '') {
    return res.status(400).json({ error: 'Strategy name is required' });
  }

  try {
    const query = `
      INSERT INTO strategies (user_id, name, description) 
      VALUES ($1, $2, $3) 
      RETURNING id, name, description, user_id
    `;
    
    // Fallback to null if empty or undefined
    const descValue = (description && description.trim() !== '') ? description.trim() : null;

    const result = await pool.query(query, [userId, name.trim(), descValue]);
    
    res.status(201).json(result.rows[0]);
  } catch (error) {
    console.error('Database Error:', error);
    res.status(500).json({ error: 'Failed to create strategy' });
  }
});

// 2. DELETE STRATEGY (For Settings Page)
app.delete('/api/strategies/:id', requireAuth, async (req, res) => {
  const { id } = req.params;
  const userId = req.session.userId;

  try {
    const result = await pool.query(
      'DELETE FROM strategies WHERE id = $1 AND user_id = $2 RETURNING id',
      [id, userId]
    );

    if (result.rows.length === 0) {
      return res.status(404).json({ error: 'Strategy not found or unauthorized' });
    }

    res.json({ success: true, message: 'Strategy deleted successfully' });
  } catch (error) {
    console.error('Database Error:', error);
    res.status(500).json({ error: 'Failed to delete strategy' });
  }
});

// --- EXTERNAL PUBLIC TRADE ROUTES ---

// 1. Fetch Strategies by User Email (Public)
app.get('/api/external/strategies', async (req, res) => {
  const { email } = req.query;

  try {
    let query = 'SELECT id, name FROM strategies WHERE user_id IS NULL';
    let params = [];

    if (email) {
      const userRes = await pool.query('SELECT id FROM users WHERE email = $1', [email.trim().toLowerCase()]);
      if (userRes.rows.length > 0) {
        query = 'SELECT id, name FROM strategies WHERE user_id = $1 OR user_id IS NULL';
        params = [userRes.rows[0].id];
      }
    }

    const result = await pool.query(query, params);
    res.json(result.rows);
  } catch (err) {
    console.error('Error fetching strategies:', err);
    res.status(500).json({ error: 'Failed to fetch strategies' });
  }
});

// 2. Add Trade using Email (Public)
app.post('/api/external/trades', async (req, res) => {
  try {
    const {
      email,
      symbol,
      instrument_type,
      expiry_date,
      entry_price,
      exit_price,
      quantity,
      lot_size,
      strategy_id,
      trade_date,
      entry_time,
      exit_time,
      market_close_strike, // <--- Add here
      notes
    } = req.body;

    if (!email || !email.trim()) {
      return res.status(400).json({ error: 'User Email ID is required' });
    }

    const userRes = await pool.query('SELECT id FROM users WHERE email = $1', [email.trim().toLowerCase()]);
    if (userRes.rows.length === 0) {
      return res.status(404).json({ error: 'No user account found with this email address' });
    }

    const userId = userRes.rows[0].id;

    const pnl = (parseFloat(exit_price) - parseFloat(entry_price)) * parseInt(quantity) * parseInt(lot_size || 1);

    const query = `
      INSERT INTO trades (
        user_id, symbol, instrument_type, expiry_date, 
        entry_price, exit_price, quantity, lot_size, 
        pnl, strategy_id, trade_date, entry_time, exit_time, 
        market_close_strike, notes
      ) 
      VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13, $14, $15)
      RETURNING *
    `;

    const values = [
      userId,
      symbol,
      instrument_type,
      expiry_date || null,
      entry_price,
      exit_price,
      quantity,
      lot_size,
      pnl,
      strategy_id || null,
      trade_date,
      entry_time || null,
      exit_time || null,
      market_close_strike ? parseFloat(market_close_strike) : null, // <--- Add here
      notes || null
    ];

    const result = await pool.query(query, values);
    res.status(201).json({ success: true, trade: result.rows[0] });
  } catch (err) {
    console.error('External Trade Creation Error:', err);
    res.status(500).json({ error: 'Failed to log trade via external form' });
  }
});

app.use('/api/*', (req, res) => {
  res.status(404).json({ error: `API route not found: ${req.originalUrl}` });
});

app.listen(PORT, () => console.log(`Server running on http://localhost:3000`));