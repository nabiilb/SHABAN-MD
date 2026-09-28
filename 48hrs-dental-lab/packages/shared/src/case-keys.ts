/** Human-readable reference builders shared by the mock API and tests. */
export function caseNumber(year: number, seq: number) {
  return `DL-${year}-${String(seq).padStart(5, '0')}`;
}

export function invoiceNumber(year: number, seq: number) {
  return `INV-${year}-${String(seq).padStart(5, '0')}`;
}

export function patientCode(seq: number) {
  return `PT-${seq}`;
}
