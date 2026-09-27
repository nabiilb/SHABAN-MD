import { create } from 'zustand';

interface UiState {
  mobileNavOpen: boolean;
  searchOpen: boolean;
  pageTitle: string;
  pageSubtitle: string;
  setMobileNav: (open: boolean) => void;
  setSearchOpen: (open: boolean) => void;
  setPage: (title: string, subtitle?: string) => void;
}

/** Purely UI state. Server data lives in TanStack Query, never here. */
export const useUiStore = create<UiState>((set) => ({
  mobileNavOpen: false,
  searchOpen: false,
  pageTitle: '',
  pageSubtitle: '',
  setMobileNav: (mobileNavOpen) => set({ mobileNavOpen }),
  setSearchOpen: (searchOpen) => set({ searchOpen }),
  setPage: (pageTitle, pageSubtitle = '') => set({ pageTitle, pageSubtitle }),
}));
