/** "12 KB", "3.4 MB": binary units, the units come from the copy file so they can be translated. */
export function formatFileSize(
  bytes: number,
  units: { bytes: string; kb: string; mb: string; gb: string },
): string {
  if (bytes < 1024) return `${bytes} ${units.bytes}`
  if (bytes < 1024 ** 2) return `${Math.ceil(bytes / 1024)} ${units.kb}`
  if (bytes < 1024 ** 3) return `${(bytes / 1024 ** 2).toFixed(1)} ${units.mb}`
  return `${(bytes / 1024 ** 3).toFixed(1)} ${units.gb}`
}
