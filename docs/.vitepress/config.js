import { defineConfig } from 'vitepress'

export default defineConfig({
  title: 'Solo Input',
  description: 'Typed, validated input DTOs for PHP 8.3+, built through their constructor from HTTP input.',
  base: '/Input/',
  
  head: [
    ['link', { rel: 'icon', type: 'image/svg+xml', href: '/Input/logo.svg' }],
    ['meta', { name: 'theme-color', content: '#10b981' }],
    ['meta', { property: 'og:type', content: 'website' }],
    ['meta', { property: 'og:title', content: 'Solo Input' }],
    ['meta', { property: 'og:description', content: 'Typed, validated input DTOs for PHP 8.3+' }],
  ],

  themeConfig: {
    logo: '/logo.svg',
    
    nav: [
      { text: 'Guide', link: '/guide/installation' },
      { text: 'Features', link: '/features/field-attribute' },
      { text: 'API', link: '/api/input-factory' },
      { text: 'v1.0.0', link: 'https://github.com/SoloPHP/Input/releases' },
      {
        text: 'Links',
        items: [
          { text: 'GitHub', link: 'https://github.com/SoloPHP/Input' },
          { text: 'Packagist', link: 'https://packagist.org/packages/solophp/input' },
          { text: 'SoloPHP', link: 'https://github.com/SoloPHP' }
        ]
      }
    ],

    sidebar: [
      {
        text: 'Getting Started',
        items: [
          { text: 'Installation', link: '/guide/installation' },
          { text: 'Quick Start', link: '/guide/quick-start' },
          { text: 'Configuration', link: '/guide/configuration' }
        ]
      },
      {
        text: 'Features',
        items: [
          { text: 'Attributes', link: '/features/field-attribute' },
          { text: 'Type Casting', link: '/features/type-casting' },
          { text: 'Processing Stages', link: '/features/processors' },
          { text: 'Generators', link: '/features/generators' },
          { text: 'Nested Objects & Items', link: '/features/nested-items' },
          { text: 'Field Grouping', link: '/features/grouping' },
          { text: 'Validation', link: '/features/validation' },
          { text: 'Schema Export', link: '/features/schema' }
        ]
      },
      {
        text: 'API Reference',
        items: [
          { text: 'InputFactory & Inputs', link: '/api/input-factory' },
          { text: 'Attributes', link: '/api/field-attribute' },
          { text: 'Exceptions', link: '/api/exceptions' }
        ]
      }
    ],

    socialLinks: [
      { icon: 'github', link: 'https://github.com/SoloPHP/Input' }
    ],

    footer: {
      message: 'Released under the MIT License.',
      copyright: 'Copyright © 2026 SoloPHP'
    },

    search: {
      provider: 'local'
    },

    editLink: {
      pattern: 'https://github.com/SoloPHP/Input/edit/main/docs/:path',
      text: 'Edit this page on GitHub'
    }
  }
})
