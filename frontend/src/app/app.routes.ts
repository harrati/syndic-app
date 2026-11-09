import { Routes } from '@angular/router';
import { authGuard, guestGuard } from './core/auth.guard';

export const routes: Routes = [
  {
    path: 'login',
    title: 'Connexion · SyndicApp',
    canActivate: [guestGuard],
    loadComponent: () => import('./features/login/login.component').then((m) => m.LoginComponent),
  },
  {
    path: 'bilan',
    title: 'Bilan · SyndicApp',
    canActivate: [authGuard],
    loadComponent: () => import('./features/dashboard/dashboard.component').then((m) => m.DashboardComponent),
  },
  {
    path: 'copropriete',
    title: 'Copropriété · SyndicApp',
    canActivate: [authGuard],
    loadComponent: () =>
      import('./features/copropriete/copropriete.component').then((m) => m.CoproprieteComponent),
  },
  { path: '', pathMatch: 'full', redirectTo: 'bilan' },
  { path: '**', redirectTo: 'bilan' },
];
