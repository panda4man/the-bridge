import { openDb, resetStuckDeployments } from './db.js';
import app from './app.js';

const db = openDb();
resetStuckDeployments(db);

const port = process.env.PORT || 3000;
app.listen(port, () => {
  console.log(`Bridge running on http://localhost:${port}`);
});
