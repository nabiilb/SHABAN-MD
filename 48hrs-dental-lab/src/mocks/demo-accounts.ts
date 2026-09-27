/** Demo sign-ins shown on the login page in mock mode only (never in API mode). */
export const DEMO_PASSWORD = '48hrs-demo';

export const DEMO_ACCOUNTS = [
  { role: 'Super Admin', email: 'khalid@48hrs.lab' },
  { role: 'Admin', email: 'hodan@48hrs.lab' },
  { role: 'Lab Manager', email: 'omar@48hrs.lab' },
  { role: 'Reception', email: 'sagal@48hrs.lab' },
  { role: 'Technician', email: 'fatima@48hrs.lab' },
  { role: 'Quality Control', email: 'idil@48hrs.lab' },
  { role: 'Delivery', email: 'bashir@48hrs.lab' },
  { role: 'Client', email: 'amina@smiledental.so' },
] as const;
