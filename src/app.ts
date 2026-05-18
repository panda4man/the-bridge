import express, { Application, Request, Response, NextFunction } from 'express';
import session from 'express-session';
import flash from 'connect-flash';
import methodOverride from 'method-override';
import { fileURLToPath } from 'url';
import { dirname, join } from 'path';
import appsRouter from './routes/apps.js';
import deploymentsRouter from './routes/deployments.js';

const __dirname = dirname(fileURLToPath(import.meta.url));

const app: Application = express();

app.set('view engine', 'ejs');
app.set('views', join(__dirname, 'views'));

app.use(express.json());
app.use(express.urlencoded({ extended: true }));
app.use(methodOverride((req) => {
  if (req.body && '_method' in (req.body as Record<string, unknown>)) {
    const method = (req.body as Record<string, string>)['_method'];
    delete (req.body as Record<string, unknown>)['_method'];
    return method;
  }
  return '';
}));
app.use(session({
  secret: process.env.SESSION_SECRET || 'bridge-secret',
  resave: false,
  saveUninitialized: false,
}));
app.use(flash());

app.use((req: Request, res: Response, next: NextFunction) => {
  res.locals['success'] = req.flash('success');
  next();
});

app.use('/', appsRouter);
app.use('/', deploymentsRouter);

export default app;
