import { Suspense } from 'react';
import { Outlet } from 'react-router-dom';
import logo from '@/assets/logo-48hrs.png';
import { PageLoader } from '@/components/ui/feedback';
import { Wordmark } from '@/components/layout/brand';

/** Split login layout from the prototype: navy brand panel + white form panel. */
export function AuthLayout() {
  return (
    <div className="grid min-h-dvh md:grid-cols-[1.05fr_1fr]">
      <section className="flex flex-col justify-between gap-8 bg-navy-900 px-6 py-8 text-white sm:px-10 md:px-14 md:py-14">
        <Wordmark size="lg" />
        <div className="flex max-w-[420px] flex-col gap-3.5">
          <p className="font-display text-[28px] leading-[1.15] font-extrabold sm:text-[34px] lg:text-[42px]">Every case. Within 48 hours.</p>
          <p className="text-[15px] leading-relaxed text-navy-200">
            Case intake, production, quality control and delivery — one controlled workflow with a live 48-hour commitment on every case.
          </p>
        </div>
        <div className="hidden flex-col gap-1.5 md:flex">
          <span className="text-[11px] font-bold tracking-[.16em] text-gold-500">SECURE ACCESS</span>
          <span className="text-[13px] leading-normal text-navy-200">Your role decides what you can see and do. Disabled accounts cannot sign in.</span>
        </div>
      </section>
      <section className="flex flex-col justify-center bg-card px-5 py-8 sm:px-10 md:px-12">
        <div className="mx-auto flex w-full max-w-[440px] flex-col gap-6">
          <img src={logo} alt="48HRS Dental Lab" className="h-16 w-auto self-start" />
          <Suspense fallback={<PageLoader />}>
            <Outlet />
          </Suspense>
        </div>
      </section>
    </div>
  );
}
