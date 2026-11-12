import { defineConfig } from 'tsup';

export default defineConfig({
  entry: { index: 'src/index.ts' },
  format: ['esm', 'cjs'],
  dts: true,
  tsconfig: 'tsconfig.build.json',
  platform: 'neutral',
  target: 'es2022',
  clean: true,
  sourcemap: false,
  splitting: false,
  treeshake: true,
});
