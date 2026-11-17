export function setFixtureEnv(values: Record<string, string | undefined>): () => void {
  const previous = new Map(Object.keys(values).map(key => [key, process.env[key]]));
  const write = (key: string, value: string | undefined) => {
    if (value === undefined) delete process.env[key];
    else process.env[key] = value;
  };
  for (const [key, value] of Object.entries(values)) write(key, value);
  return () => {
    for (const [key, value] of previous) write(key, value);
  };
}
