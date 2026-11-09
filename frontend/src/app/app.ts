import { Component, computed, inject } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { NavigationEnd, Router, RouterLink, RouterOutlet } from '@angular/router';
import { filter, map } from 'rxjs';
import { AuthService } from './core/auth.service';
import { UiService } from './core/ui.service';
import { ExpenseModalComponent } from './features/expense-modal/expense-modal.component';
import { PaymentModalComponent } from './features/payment-modal/payment-modal.component';

type NavAction = 'payment' | 'expense';

interface NavItem {
  label: string;
  icon: string;
  link?: string;
  action?: NavAction;
}

@Component({
  selector: 'app-root',
  standalone: true,
  imports: [RouterOutlet, RouterLink, PaymentModalComponent, ExpenseModalComponent],
  templateUrl: './app.html',
})
export class App {
  private readonly router = inject(Router);
  private readonly auth = inject(AuthService);
  protected readonly ui = inject(UiService);

  private readonly currentUrl = toSignal(
    this.router.events.pipe(
      filter((event): event is NavigationEnd => event instanceof NavigationEnd),
      map((event) => event.urlAfterRedirects),
    ),
    { initialValue: this.router.url },
  );

  protected readonly onLoginPage = computed(() => this.currentUrl().startsWith('/login'));
  protected readonly showChrome = computed(() => this.auth.isAuthenticated() && !this.onLoginPage());
  protected readonly residence = computed(() => this.auth.tenant()?.name ?? 'SyndicApp');
  protected readonly userInitials = computed(() => initials(this.auth.user()?.full_name ?? 'Syndic'));

  protected readonly navItems: readonly NavItem[] = [
    { label: 'Bilan', icon: 'M4 20V10M10 20V4M16 20v-7M22 20H2', link: '/bilan' },
    {
      label: 'Copropriété',
      icon: 'M4 21V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v16M16 9h2a2 2 0 0 1 2 2v10M8 7h2M8 11h2M8 15h2',
      link: '/copropriete',
    },
    { label: 'Paiements', icon: 'M2.5 7.5h19v9h-19zM6 12h.01M18 12h.01', action: 'payment' },
    { label: 'Dépenses', icon: 'M6 3h12v18l-3-2-3 2-3-2-3 2zM9 8h6M9 12h6', action: 'expense' },
  ];

  constructor() {
    // A restored token is revalidated once on startup; a 401 clears it in the interceptor.
    if (this.auth.isAuthenticated()) {
      this.auth.refreshSession().subscribe({ error: () => undefined });
    }
  }

  protected isActive(item: NavItem): boolean {
    return item.link !== undefined && this.currentUrl().startsWith(item.link);
  }

  protected handleAction(action: NavAction | undefined): void {
    if (action === 'payment') {
      this.ui.openPayment();
    } else if (action === 'expense') {
      this.ui.openExpense();
    }
  }

  protected logout(): void {
    this.auth.logout();
    void this.router.navigate(['/login']);
  }
}

function initials(name: string): string {
  const parts = name.trim().split(/\s+/).filter(Boolean);
  const letters = parts
    .slice(0, 2)
    .map((part) => part.charAt(0).toUpperCase())
    .join('');

  return letters || 'SA';
}
