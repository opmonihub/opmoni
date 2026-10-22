type AdmissionOptions = {
  signal?: AbortSignal | null
  isCurrent?: () => boolean
}

type Job<T> = {
  run: () => T | Promise<T>
  options: AdmissionOptions
  resolve: (value: T | PromiseLike<T>) => void
  reject: (reason?: unknown) => void
  abort?: () => void
}

export function createAccessApiAdmissionQueue() {
  const pending: Job<unknown>[] = []
  let active = false

  function pump() {
    if (active) return
    const job = pending.shift()
    if (!job) return
    const { signal, isCurrent } = job.options
    if (signal && job.abort)
      signal.removeEventListener('abort', job.abort)
    if (signal?.aborted) {
      job.reject(abortError())
      pump()
      return
    }
    if (isCurrent && !isCurrent()) {
      job.reject(abortError('The access context changed before the request was sent.'))
      pump()
      return
    }

    active = true
    Promise.resolve().then(() => {
      if (signal?.aborted)
        throw abortError()
      if (isCurrent && !isCurrent())
        throw abortError('The access context changed before the request was sent.')
      return job.run()
    }).then(job.resolve, job.reject).finally(() => {
      active = false
      pump()
    })
  }

  return {
    run<T>(run: () => T | Promise<T>, options: AdmissionOptions = {}): Promise<T> {
      if (options.signal?.aborted)
        return Promise.reject(abortError())
      if (options.isCurrent && !options.isCurrent())
        return Promise.reject(abortError('The access context changed before the request was sent.'))

      return new Promise<T>((resolve, reject) => {
        const job: Job<T> = { run, options, resolve, reject }
        if (options.signal) {
          job.abort = () => {
            const index = pending.indexOf(job as Job<unknown>)
            if (index !== -1) {
              pending.splice(index, 1)
              reject(abortError())
            }
          }
          options.signal.addEventListener('abort', job.abort, { once: true })
        }
        pending.push(job as Job<unknown>)
        pump()
      })
    }
  }
}

const queues = new WeakMap<object, ReturnType<typeof createAccessApiAdmissionQueue>>()

/** Clone JSON-shaped request values while leaving native fetch bodies and their ownership intact. */
export function snapshotAccessApiValue<T>(value: T): T {
  const seen = new WeakMap<object, unknown>()
  return cloneJsonShape(value, seen) as T
}

/** Preserve the JSON representation at the same root that ofetch serializes. */
export function snapshotAccessApiBody<T>(value: T, contentType?: string): T {
  const source = unwrapVueValue(value)
  if (isNativeRequestBody(source)) return source as T
  if (source && typeof source !== 'string' && isOfetchJsonSerializable(source)
    && contentType === 'application/x-www-form-urlencoded')
    return new URLSearchParams(source as Record<string, string>).toString() as T
  if (hasJsonHook(source, new WeakSet())) return captureJson(source as object) as T
  return snapshotAccessApiValue(source) as T
}

/** ufo serializes each query value (and each array item) independently. */
export function snapshotAccessApiQuery<T>(value: T): T {
  value = unwrapVueValue(value) as T
  if (!value || typeof value !== 'object' || Array.isArray(value)) return snapshotAccessApiValue(value)
  const prototype = Object.getPrototypeOf(value)
  if (prototype !== Object.prototype && prototype !== null) return snapshotAccessApiValue(value)
  const output: Record<string, unknown> = Object.create(prototype)
  for (const [key, item] of Object.entries(value)) {
    const captured = Array.isArray(item)
      ? item.map(snapshotQueryAtom)
      : snapshotQueryAtom(item)
    defineDataProperty(output, key, captured)
  }
  return output as T
}

function cloneJsonShape(value: unknown, seen: WeakMap<object, unknown>): unknown {
  if (value === null || typeof value !== 'object') return value
  const candidate = value as Record<string, unknown>
  if (candidate.__v_isRef === true) return cloneJsonShape(candidate.value, seen)
  if (isNativeRequestBody(value)) return value
  if (typeof candidate.toJSON === 'function') return captureJson(value)
  const prototype = Object.getPrototypeOf(value)
  if (!Array.isArray(value) && prototype !== Object.prototype && prototype !== null) return value
  if (seen.has(value)) return seen.get(value)
  if (Array.isArray(value)) {
    const output: unknown[] = []
    seen.set(value, output)
    for (const item of value) output.push(cloneJsonShape(item, seen))
    return output
  }
  const output: Record<string, unknown> = Object.create(prototype)
  seen.set(value, output)
  for (const [key, item] of Object.entries(value))
    defineDataProperty(output, key, cloneJsonShape(item, seen))
  return output
}

function snapshotQueryAtom(value: unknown): unknown {
  const source = unwrapVueValue(value)
  return source !== null && typeof source === 'object' ? captureJson(source) : source
}

function captureJson(value: object): object {
  const serialized = JSON.stringify(value)
  const captured = serialized === undefined ? undefined : JSON.parse(serialized) as unknown
  return { toJSON: () => captured }
}

function hasJsonHook(value: unknown, seen: WeakSet<object>): boolean {
  if (value === null || typeof value !== 'object') return false
  const candidate = value as Record<string, unknown>
  if (candidate.__v_isRef === true) return hasJsonHook(candidate.value, seen)
  if (isNativeRequestBody(value)) return false
  if (typeof candidate.toJSON === 'function') return true
  const prototype = Object.getPrototypeOf(value)
  if (!Array.isArray(value) && prototype !== Object.prototype && prototype !== null) return false
  if (seen.has(value)) return false
  seen.add(value)
  return Object.values(value).some(item => hasJsonHook(item, seen))
}

function unwrapVueValue(value: unknown): unknown {
  if (!value || typeof value !== 'object') return value
  const candidate = value as Record<string, unknown>
  if (candidate.__v_isRef === true) return unwrapVueValue(candidate.value)
  return value
}

function isNativeRequestBody(value: unknown): value is BodyInit {
  return value instanceof FormData || value instanceof Blob || value instanceof URLSearchParams
    || value instanceof ArrayBuffer || ArrayBuffer.isView(value) || value instanceof ReadableStream
    || (typeof File !== 'undefined' && value instanceof File)
}

function isOfetchJsonSerializable(value: unknown): boolean {
  if (value === undefined) return false
  const type = typeof value
  if (type === 'string' || type === 'number' || type === 'boolean') return true
  if (type !== 'object' || value === null) return false
  if (Array.isArray(value)) return true
  if ((value as { buffer?: unknown }).buffer) return false
  if (value instanceof FormData || value instanceof URLSearchParams) return false
  const candidate = value as Record<string, unknown>
  return (!!candidate.constructor && (candidate.constructor as { name?: string }).name === 'Object')
    || typeof candidate.toJSON === 'function'
}

function defineDataProperty(target: Record<string, unknown>, key: string, value: unknown) {
  Object.defineProperty(target, key, { configurable: true, enumerable: true, writable: true, value })
}

export function useAccessApiAdmissionQueue(nuxtApp: object) {
  let queue = queues.get(nuxtApp)
  if (!queue) {
    queue = createAccessApiAdmissionQueue()
    queues.set(nuxtApp, queue)
  }
  return queue
}

function abortError(message = 'The request was aborted before it was sent.') {
  return new DOMException(message, 'AbortError')
}
