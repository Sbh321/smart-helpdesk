import { Tabs as TabsPrimitive } from '@base-ui/react/tabs'
import { cn } from '@/lib/utils'

export const Tabs = TabsPrimitive.Root

export function TabsList({ className, ...props }: TabsPrimitive.List.Props) {
  return <TabsPrimitive.List className={cn('flex flex-wrap gap-1 border-b border-border', className)} {...props} />
}

export function TabsTrigger({ className, ...props }: TabsPrimitive.Tab.Props) {
  return <TabsPrimitive.Tab className={cn('border-b-2 border-transparent px-3 py-2 text-sm text-muted-foreground outline-none focus-visible:ring-2 focus-visible:ring-ring data-active:border-primary data-active:text-foreground', className)} {...props} />
}

export function TabsContent({ className, ...props }: TabsPrimitive.Panel.Props) {
  return <TabsPrimitive.Panel className={cn('py-5 outline-none focus-visible:ring-2 focus-visible:ring-ring', className)} {...props} />
}
