import { openDb, resetDb } from '../src/db.js';
import { tmpdir } from 'os';

process.env.REPOS_PATH = tmpdir();

beforeEach(() => {
  openDb(':memory:');
});

afterEach(() => {
  resetDb();
});
