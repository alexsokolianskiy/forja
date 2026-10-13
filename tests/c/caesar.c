#include <stdint.h>
#include <stddef.h>
#include <stdlib.h>

#define LETTERS 26

static int64_t rotate(int64_t code, int64_t base, int64_t shift)
{
    return base + ((code - base + shift) % LETTERS + LETTERS) % LETTERS;
}

char *{{symbol}}(const char *s, size_t s_len, int64_t shift, int64_t *forja_len, int32_t *forja_err)
{
    *forja_len = 0;
    if (s_len == 0) {
        return NULL;
    }
    char *out = malloc(s_len);
    if (out == NULL) {
        *forja_err = 1;
        return NULL;
    }
    for (size_t i = 0; i < s_len; i++) {
        unsigned char c = (unsigned char) s[i];
        if (c >= 'a' && c <= 'z') {
            out[i] = (char) rotate(c, 'a', shift);
        } else if (c >= 'A' && c <= 'Z') {
            out[i] = (char) rotate(c, 'A', shift);
        } else {
            out[i] = (char) c;
        }
    }
    *forja_len = (int64_t) s_len;
    return out;
}
