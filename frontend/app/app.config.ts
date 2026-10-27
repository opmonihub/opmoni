export default defineAppConfig({
  ui: {
    colors: {
      primary: 'green',
      neutral: 'zinc'
    },
    button: {
      defaultVariants: {
        color: 'neutral'
      },
      compoundVariants: [
        {
          color: 'primary',
          class: 'outline-primary'
        },
        {
          color: 'success',
          class: 'outline-success'
        },
        {
          color: 'primary',
          variant: 'solid',
          class: 'hover:bg-primary/90 active:bg-primary/90 dark:hover:bg-primary/75 dark:active:bg-primary/75'
        },
        {
          color: 'success',
          variant: 'solid',
          class: 'hover:bg-success/90 active:bg-success/90 dark:hover:bg-success/75 dark:active:bg-success/75'
        }
      ]
    }
  }
})
