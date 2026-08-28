import type { CapacitorConfig } from '@capacitor/cli';

const config: CapacitorConfig = {
  appId: 'com.nenestore.app',
  appName: 'NENE Store',
  webDir: 'public',
  server: {
    url: 'https://www.nenetopup.com',
    cleartext: false
  }
};

export default config;
