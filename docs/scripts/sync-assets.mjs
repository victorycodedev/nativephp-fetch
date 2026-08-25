import { copyFile, mkdir } from "node:fs/promises";
import { fileURLToPath } from "node:url";

const source = fileURLToPath(
  new URL("../../resources/icon.png", import.meta.url),
);
const publicDirectory = fileURLToPath(new URL("../public", import.meta.url));
const destination = fileURLToPath(
  new URL("../public/icon.png", import.meta.url),
);

await mkdir(publicDirectory, { recursive: true });
await copyFile(source, destination);
