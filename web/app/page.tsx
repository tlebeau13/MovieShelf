export default function Home() {
  return (
    <main className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-8 px-6 py-16">
      <header className="flex flex-col gap-3">
        <h1 className="text-3xl font-semibold tracking-tight">MovieShelf</h1>
        <p className="max-w-prose text-lg text-zinc-600 dark:text-zinc-400">
          Bestseller lists meet their screen adaptations. How long books chart, how
          genres shift, and whether a beloved book makes a well-rated film.
        </p>
      </header>

      <section className="rounded-lg border border-dashed border-zinc-300 p-8 dark:border-zinc-700">
        <h2 className="font-medium">No charts yet</h2>
        <p className="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
          The rank-over-time chart lands in #17 and the flagship dashboard views in
          #18. Until the API exposes the MART tables (#16), data comes from the stub
          routes under <code className="font-mono text-xs">app/api/stub/</code>.
        </p>
      </section>
    </main>
  );
}
